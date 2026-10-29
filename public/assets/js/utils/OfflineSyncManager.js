/**
 * VendGuard - Motor de la cola sin conexión (utils/OfflineSyncManager.js)
 *
 * Orquesta lo que ocurre entre la pulsación del técnico en el sótano y el
 * expediente del servidor (Módulo 24, RF-03 a RF-06):
 *
 *  - **Encolado validado (`D1`, `D3`, `D5`, `QA 1`, `QA 3`)**: antes de admitir
 *    una acción se comprueba la vigencia de la copia local, el precinto físico
 *    cuando hay riesgo sanitario en máquina perecedera y la cuota fotográfica.
 *    Una acción rechazada no deja rastro en la bandeja.
 *  - **Encadenamiento (`QA 4`)**: el paso de cierre de una parada enlaza con el
 *    inicio previo de esa misma parada (`chain_started_at` / `chain_ended_at`)
 *    para que el servidor pueda preservar la duración real `Δt` aunque el reloj
 *    del teléfono esté desajustado.
 *  - **Sincronización FIFO (`RF-04.1`)**: al recuperar cobertura se envía una
 *    operación tras otra, en orden estricto de creación.
 *  - **Aislamiento de conflictos (`RF-05.1`, `QA 3`)**: una transición rechazada
 *    marca las transiciones posteriores de esa parada, pero un comentario
 *    intermedio que falle no arrastra el cierre: solo se marca él mismo.
 *  - **Cero descarte silencioso (`Art. III.1`, `Art. III.3`, `QA 1`, `QA 2`)**: un
 *    conflicto solo desaparece de la bandeja con el volcado al expediente, y una
 *    cuenta dada de baja vuelca su cola completa antes de liberar el dispositivo.
 *
 * El envío usa el transporte de la casa (`api.js`), de modo que el plazo de 5 s,
 * la detección de desconexión y la cabecera `Authorization` son los mismos que
 * en cualquier otra petición: la cola no abre un camino paralelo al backend.
 *
 * Dogma Vanilla: ESM nativo, cero dependencias externas.
 */

import api, { buildMultipartBody } from '../api.js';
import {
  MUTATION_STATUS,
  OFFLINE_OPERATIONS,
  OFFLINE_MESSAGES,
  OfflineQueueError,
  OfflineStorage,
  PERISHABLE_MACHINE_TYPES,
  evaluateSnapshotValidity,
  extractConsumedCatalogParts,
  photoAttachmentBlob,
  requiresSanitarySeal,
  serializePhoto,
  validatePhotoAttachment
} from './OfflineStorage.js';

/** Evento nativo con el que la bandeja anuncia cambios de estado (`EARS 06.1`). */
export const OFFLINE_QUEUE_EVENT = 'vendguard:offline-queue-change';

/** Operaciones de inicio que abren una cadena temporal en la parada (`QA 4`). */
const CHAIN_START_OPERATIONS = Object.freeze([
  'START_INCIDENT',
  'RESUME_INCIDENT_PENDING_INFO',
  'START_PREVENTIVE'
]);

/** Operaciones de cierre que pueden enlazarse con ese inicio. */
const CHAIN_END_OPERATIONS = Object.freeze([
  'PAUSE_INCIDENT_PARTS',
  'PAUSE_INCIDENT_PENDING_INFO',
  'RESOLVE_INCIDENT',
  'COMPLETE_PREVENTIVE',
  'REINSPECT_PREVENTIVE'
]);

/** Código con el que el middleware rechaza a una cuenta dada de baja (`QA 5`). */
const ACCOUNT_DEACTIVATED_CODE = 'ACCOUNT_DEACTIVATED';

/**
 * Códigos 4xx que el servidor emite como «vuelve más tarde», no como veredicto.
 *
 * El resto de 4xx es una decisión definitiva sobre esa operación: reintentarla
 * no la va a arreglar y, en cabeza del FIFO, bloquea a todas las que vienen
 * detrás (`RF-04.1`).
 */
const RETRYABLE_CLIENT_STATUSES = Object.freeze([408, 425, 429]);

/** Motivo visible cuando el rechazo es por tamaño de la evidencia (`413`). */
const PAYLOAD_TOO_LARGE_MESSAGE = 'El servidor rechazó la fotografía por tamaño. '
  + 'El trabajo queda en conflicto para volcarlo al expediente sin la evidencia.';

/** Instante del conflicto que bloquea las transiciones posteriores de la parada. */
const CHAIN_BLOCKED_MESSAGE = 'Paso previo de esta parada rechazado por conflicto en el servidor.';

const MUTATION_ID_PATTERN = /^[A-Za-z0-9_.:-]{8,64}$/;

/**
 * Genera la clave única de operación con el formato del contrato §6.2.
 *
 * `mut_20261027_091523_a8f49c12b3e14d90`: legible en el log del servidor, único
 * por operación y dentro del rango 8–64 del patrón admitido.
 *
 * @param {Date|number} [now=new Date()] Instante de creación (inyectable en pruebas).
 * @param {string|null} [suffix=null] Sufijo determinista para las certificaciones.
 * @returns {string}
 */
export function generateClientMutationId(now = new Date(), suffix = null) {
  const date = now instanceof Date ? now : new Date(now);
  const pad = (value) => String(value).padStart(2, '0');
  const stamp = `${date.getUTCFullYear()}${pad(date.getUTCMonth() + 1)}${pad(date.getUTCDate())}`
    + `_${pad(date.getUTCHours())}${pad(date.getUTCMinutes())}${pad(date.getUTCSeconds())}`;
  const tail = suffix === null ? randomHex(16) : String(suffix);
  const id = `mut_${stamp}_${tail}`;

  if (!MUTATION_ID_PATTERN.test(id)) {
    throw new OfflineQueueError(
      'INVALID_CLIENT_MUTATION_ID',
      'No se pudo generar un identificador válido para la operación.'
    );
  }

  return id;
}

/**
 * Resumen estructurado del trabajo realizado in situ, tal como lo exige el
 * volcado de conflicto (`§6.3`) y el volcado por baja de cuenta (`§6.4`).
 *
 * El servidor rechaza un resumen sin ningún texto ni pieza: aquí se compone con
 * lo que el técnico ya declaró al encolar, incluida la constancia textual de la
 * firma o de su ausencia (`EARS 05.3.1`).
 *
 * @param {Object} item Elemento de la bandeja
 * @returns {Object}
 */
export function buildOfflineWorkSummary(item) {
  const payload = item?.payload ?? {};
  const sheet = payload?.service_sheet ?? null;
  const declaredText = textOrNull(
    payload.pause_reason ?? payload.pending_parts_reason ?? payload.reason_text
  ) ?? textOrNull(payload.notes);

  return {
    diagnosis: textOrNull(payload.diagnosis),
    action_taken: textOrNull(payload.action_taken),
    pause_reason: declaredText,
    // Un paso de estado puede rechazarse sin parte de trabajo (un inicio): el
    // volcado sigue siendo obligatorio y deja constancia de lo ocurrido.
    notes: declaredText
      ?? `Actuación ejecutada sin conexión (${item?.operation_type ?? 'operación'} sobre `
        + `${item?.stop_type ?? 'parada'} #${item?.stop_id ?? 0}) rechazada al sincronizar.`,
    replaced_parts: extractConsumedCatalogParts(item?.operation_type, payload).map((part) => ({
      spare_part_id: part.spare_part_id,
      quantity: part.quantity
    })),
    conformity_summary: conformitySummary(sheet)
  };
}

/**
 * Motor de encolado y sincronización de la bandeja del técnico.
 */
export class OfflineSyncManager {
  /**
   * @param {{storage?: OfflineStorage, client?: Object, now?: function(): number,
   *   dispatch?: function(Object): void}} [options={}]
   */
  constructor(options = {}) {
    this.storage = options.storage ?? new OfflineStorage();
    this.client = options.client ?? api;
    this.now = typeof options.now === 'function' ? options.now : () => Date.now();
    this.dispatch = typeof options.dispatch === 'function' ? options.dispatch : null;
  }

  /**
   * Descarga y guarda la copia local de la ruta (`RF-02.1`).
   *
   * @param {number} technicianId
   * @returns {Promise<{snapshot: Object, validity: Object}>}
   */
  async refreshSnapshot(technicianId) {
    const bootstrap = await this.client.technician.getOfflineBootstrap();
    const snapshot = await this.storage.saveSnapshot(bootstrap);
    const validity = evaluateSnapshotValidity(snapshot, this.now());
    this.announce({ type: 'snapshot_refreshed', technicianId: Number(technicianId), validity });

    return { snapshot, validity };
  }

  /**
   * Valida y encola una acción sin conexión, reflejándola al instante en la
   * copia local (`RF-03`, `EARS 03.3`, `EARS 03.4`, `EARS 03.5`).
   *
   * @param {Object} request
   * @param {number} request.technicianId
   * @param {string} request.stopType
   * @param {number|string} request.stopId
   * @param {string} request.operationType
   * @param {Object} [request.payload={}]
   * @param {{name?: string, type?: string, size?: number, blob?: Blob}|null} [request.photoFile=null]
   * @param {boolean} [request.sanitarySealConfirmed=false]
   * @param {string|null} [request.technicianEmail=null]
   * @param {string|null} [request.ticketOrOrderCode=null]
   * @param {number|null} [request.machineId=null]
   * @param {string|null} [request.machineCode=null]
   * @param {string|null} [request.machineType=null]
   * @returns {Promise<Object>} Elemento encolado
   * @throws {OfflineQueueError}
   */
  async enqueue(request) {
    const technicianId = Number(request?.technicianId ?? 0);
    const operationType = String(request?.operationType ?? '');
    const stopType = String(request?.stopType ?? '');
    const operation = OFFLINE_OPERATIONS[operationType];

    if (!operation || operation.stopType !== stopType) {
      throw new OfflineQueueError('UNSUPPORTED_OFFLINE_OPERATION', OFFLINE_MESSAGES.OPERATION_NOT_OFFLINE);
    }

    const snapshot = await this.storage.getSnapshot(technicianId);
    const validity = evaluateSnapshotValidity(snapshot, this.now());
    if (!validity.canEnqueueNewActions) {
      throw new OfflineQueueError('SNAPSHOT_EXPIRED_READONLY', validity.errorMessage);
    }

    const payload = request?.payload ?? {};

    if (requiresSanitarySeal(operationType, payload, request?.machineType)
      && request?.sanitarySealConfirmed !== true) {
      throw new OfflineQueueError('SANITARY_SEAL_CONFIRMATION_REQUIRED', OFFLINE_MESSAGES.SANITARY_SEAL_REQUIRED);
    }

    const activeItems = await this.storage.listMutations(technicianId);

    // Primero se valida y después se codifica: serializar un archivo de 5 MB
    // declara su tamaño a partir del propio `File`, así que no hay razón para
    // leer la evidencia entera antes de saber si cabe en la cuota.
    if (request?.photoFile) {
      validatePhotoAttachment(request.photoFile, activeItems);
    }

    const photoAttachment = await serializePhoto(request?.photoFile ?? null);

    const occurredAt = new Date(this.now()).toISOString();
    const priorStart = this.findPriorStart(activeItems, stopType, Number(request?.stopId ?? 0));
    const chainStartedAt = priorStart !== null ? priorStart.client_occurred_at : null;

    if (priorStart !== null && CHAIN_END_OPERATIONS.includes(operationType)) {
      await this.storage.putMutation({ ...priorStart, chain_ended_at: occurredAt });
    }

    const machineType = String(request?.machineType ?? '').toUpperCase();

    const mutation = {
      client_mutation_id: generateClientMutationId(new Date(this.now())),
      technician_id: technicianId,
      technician_email: request?.technicianEmail ?? null,
      sequence_order: await this.storage.nextSequenceOrder(technicianId),
      status: MUTATION_STATUS.PENDING,
      stop_type: stopType,
      stop_id: Number(request?.stopId ?? 0),
      ticket_or_order_code: request?.ticketOrOrderCode ?? null,
      machine_id: request?.machineId ?? null,
      machine_code: request?.machineCode ?? null,
      is_perishable_machine: PERISHABLE_MACHINE_TYPES.includes(machineType),
      operation_type: operationType,
      is_state_transition: operation.isStateTransition,
      http_method: operation.method,
      endpoint: operation.path(request?.stopId),
      client_occurred_at: occurredAt,
      chain_started_at: chainStartedAt,
      chain_ended_at: null,
      sanitary_seal_confirmed: request?.sanitarySealConfirmed === true,
      payload,
      photo_attachment: photoAttachment,
      provisional_parts_deducted: extractConsumedCatalogParts(operationType, payload),
      conflict: null
    };

    await this.storage.applyOptimisticMutation(technicianId, mutation);
    await this.storage.putMutation(mutation);
    this.announce({ type: 'enqueued', technicianId, mutation });

    return mutation;
  }

  /**
   * Procesa la bandeja en orden FIFO estricto (`RF-04.1`) aislado por parada
   * (`RF-05.1`, `QA 3`) y sin descartar nada en silencio (`Art. III.1`).
   *
   * @param {number} technicianId
   * @returns {Promise<{applied: number, conflicts: number, pending: number, interrupted: (string|null)}>}
   */
  async processOutboxQueue(technicianId) {
    const id = Number(technicianId);

    // Antes de vaciar nada se rescatan los envíos que quedaron huérfanos: una
    // pestaña que murió a mitad de un envío deja el elemento en `SYNCING`, y ese
    // estado no vuelve a `PENDING` por sí solo. Sin este rescate el trabajo no
    // se sincroniza nunca y el cierre de sesión queda bloqueado para siempre
    // (`EARS 06.1`). Es seguro por la idempotencia del servidor (`RF-04.2`).
    const recovered = await this.storage.recoverInterruptedMutations(id, this.now());
    if (recovered.length > 0) {
      this.announce({ type: 'queue_recovered', technicianId: id, mutations: recovered });
    }

    const queue = await this.storage.listMutations(id, [MUTATION_STATUS.PENDING]);
    const failedStateStops = new Set();
    const summary = { applied: 0, conflicts: 0, pending: queue.length, interrupted: null };

    for (const item of queue) {
      const stopKey = `${item.stop_type}:${item.stop_id}`;

      if (item.is_state_transition === true && failedStateStops.has(stopKey)) {
        await this.markAsConflict(item, {
          code: 'STATE_CHAIN_BLOCKED',
          message: CHAIN_BLOCKED_MESSAGE
        });
        await this.storage.revertProvisionalStock(id, item.provisional_parts_deducted ?? []);
        summary.conflicts++;
        summary.pending--;
        continue;
      }

      await this.storage.putMutation({
        ...item,
        status: MUTATION_STATUS.SYNCING,
        syncing_since: new Date(this.now()).toISOString()
      });
      this.announce({ type: 'syncing', technicianId: id, mutation: item });

      try {
        await this.dispatchMutation(item);
        await this.storage.deleteMutation(item.client_mutation_id);
        summary.applied++;
        summary.pending--;
      } catch (error) {
        const interrupted = await this.handleSyncFailure(id, item, error, failedStateStops, summary);
        if (interrupted !== null) {
          summary.interrupted = interrupted;
          break;
        }
      }
    }

    this.announce({ type: 'queue_processed', technicianId: id, summary });
    return summary;
  }

  /**
   * Vuelca en un solo toque el trabajo de una parada en conflicto al expediente
   * y libera su cuota fotográfica (`RF-05.3`, `QA 1`, `QA 2`).
   *
   * @param {string} clientMutationId
   * @returns {Promise<Object>} Respuesta del servidor
   */
  async dumpConflict(clientMutationId) {
    const item = await this.storage.getMutation(clientMutationId);
    if (item === null) {
      throw new OfflineQueueError(
        'OFFLINE_MUTATION_NOT_FOUND',
        'La operación ya no está en la bandeja de este dispositivo.'
      );
    }

    if (item.status !== MUTATION_STATUS.CONFLICT) {
      throw new OfflineQueueError(
        'OFFLINE_MUTATION_NOT_IN_CONFLICT',
        'Solo se puede volcar una operación marcada como en conflicto.'
      );
    }

    const response = await this.client.technician.dumpOfflineConflict(
      {
        client_mutation_id: item.client_mutation_id,
        stop_type: item.stop_type,
        stop_id: item.stop_id,
        operation_type: item.operation_type,
        client_occurred_at: item.client_occurred_at,
        chain_started_at: item.chain_started_at,
        conflict_reason: textOrNull(item.conflict?.message) ?? CHAIN_BLOCKED_MESSAGE,
        work_summary: buildOfflineWorkSummary(item)
      },
      photoAttachmentBlob(item.photo_attachment),
      item.photo_attachment?.name ?? null
    );

    await this.storage.deleteMutation(item.client_mutation_id);
    this.announce({ type: 'conflict_dumped', technicianId: item.technician_id, mutation: item });

    return response;
  }

  /**
   * Volcado automático de auditoría cuando la cuenta del técnico fue dada de
   * baja mientras trabajaba sin conexión (`EARS 06.4`, `QA 5`).
   *
   * No aplica cambios de estado ni de titularidad: solo deja constancia. Tras la
   * confirmación del servidor se vacía la copia local del dispositivo.
   *
   * @param {number} technicianId
   * @returns {Promise<{dumped: number, cleared: boolean}>}
   */
  async dumpDeactivatedAccountQueue(technicianId) {
    const id = Number(technicianId);
    const items = await this.storage.listMutations(id);

    if (items.length === 0) {
      return { dumped: 0, cleared: false };
    }

    const response = await this.client.technician.dumpDeactivatedAccountOfflineQueue(
      items.map((item) => ({
        client_mutation_id: item.client_mutation_id,
        stop_type: item.stop_type,
        stop_id: item.stop_id,
        operation_type: item.operation_type,
        client_occurred_at: item.client_occurred_at,
        chain_started_at: item.chain_started_at,
        work_summary: buildOfflineWorkSummary(item)
      }))
    );

    const cleared = response?.clear_local_storage !== false;
    if (cleared) {
      await this.storage.clearTechnician(id);
    }

    this.announce({ type: 'account_dump_completed', technicianId: id, dumped: items.length, cleared });

    return { dumped: items.length, cleared };
  }

  /**
   * Resumen de la bandeja para la barra de estado (`EARS 06.1`).
   *
   * @param {number} technicianId
   * @returns {Promise<{pending: number, syncing: number, conflicts: number, total: number,
   *   photoCount: number, photoBytes: number}>}
   */
  async getQueueSummary(technicianId) {
    const items = await this.storage.listMutations(technicianId);
    const count = (status) => items.filter((item) => item.status === status).length;
    const withPhoto = items.filter((item) => item.photo_attachment);

    return {
      pending: count(MUTATION_STATUS.PENDING),
      syncing: count(MUTATION_STATUS.SYNCING),
      conflicts: count(MUTATION_STATUS.CONFLICT),
      total: items.length,
      photoCount: withPhoto.length,
      photoBytes: withPhoto.reduce((sum, item) => sum + Number(item.photo_attachment.size ?? 0), 0)
    };
  }

  /**
   * Envía una operación con sus metadatos de sincronización (`§6.2`).
   *
   * Con fotografía adjunta se usa `multipart/form-data`, que es la única forma de
   * transportar el archivo junto al resto de campos escalares.
   *
   * @param {Object} item
   * @returns {Promise<Object>}
   */
  async dispatchMutation(item) {
    const operation = OFFLINE_OPERATIONS[item.operation_type];
    if (!operation) {
      throw new OfflineQueueError('UNSUPPORTED_OFFLINE_OPERATION', OFFLINE_MESSAGES.OPERATION_NOT_OFFLINE);
    }

    const endpoint = operation.path(item.stop_id);
    const metadata = {
      client_mutation_id: item.client_mutation_id,
      client_occurred_at: item.client_occurred_at,
      chain_started_at: item.chain_started_at,
      chain_ended_at: item.chain_ended_at,
      sanitary_seal_confirmed: item.sanitary_seal_confirmed === true
    };

    // shortcut: los campos anidados viajan serializados como JSON porque
    // multipart/form-data no los conserva; hoy solo el comentario in situ
    // adjunta foto y su cuerpo es plano.
    if (item.photo_attachment) {
      const formData = buildMultipartBody(
        { ...metadata, ...(item.payload ?? {}) },
        photoAttachmentBlob(item.photo_attachment),
        item.photo_attachment.name ?? null
      );

      return this.client.request(endpoint, { method: operation.method, body: formData });
    }

    return this.client.request(endpoint, {
      method: operation.method,
      body: { ...metadata, ...(item.payload ?? {}) }
    });
  }

  /**
   * Traduce el fallo de una operación a un desenlace de la cola.
   *
   * @returns {Promise<string|null>} Motivo de interrupción, o `null` si el bucle continúa
   */
  async handleSyncFailure(technicianId, item, error, failedStateStops, summary) {
    const status = Number(error?.status ?? 0);
    const code = String(error?.code ?? '');

    if (status === 401) {
      await this.storage.putMutation({ ...item, status: MUTATION_STATUS.PENDING });
      this.announce({ type: 'reauth_required', technicianId, mutation: item });
      return 'reauth_required';
    }

    if (status === 403 && code === ACCOUNT_DEACTIVATED_CODE) {
      await this.storage.putMutation({ ...item, status: MUTATION_STATUS.PENDING });
      try {
        await this.dumpDeactivatedAccountQueue(technicianId);
      } catch (dumpError) {
        this.announce({ type: 'account_dump_failed', technicianId, error: dumpError });
      }
      return 'account_deactivated';
    }

    if (isPermanentClientFailure(status)) {
      // `413` no se puede volcar con la misma evidencia que el servidor rechazó:
      // la foto se retira del elemento y el motivo lo deja dicho, para que el
      // trabajo llegue al expediente en lugar de quedarse bloqueado sin salida.
      const rejected = status === 413 ? { ...item, photo_attachment: null } : item;

      if (rejected.is_state_transition === true) {
        failedStateStops.add(`${rejected.stop_type}:${rejected.stop_id}`);
      }

      await this.markAsConflict(rejected, {
        code: code || 'OFFLINE_CONFLICT',
        message: status === 413
          ? PAYLOAD_TOO_LARGE_MESSAGE
          : (textOrNull(error?.message) ?? CHAIN_BLOCKED_MESSAGE)
      });
      await this.storage.revertProvisionalStock(
        technicianId,
        rejected.provisional_parts_deducted ?? []
      );
      summary.conflicts++;
      summary.pending--;

      return null;
    }

    // Fallo transitorio —caída de red, plazo agotado (408), límite de tasa (429)
    // o error del servidor—: la operación conserva su clave y vuelve a PENDING;
    // el resto de la cola espera a la próxima reconexión.
    await this.storage.putMutation({ ...item, status: MUTATION_STATUS.PENDING });
    this.announce({ type: 'sync_interrupted', technicianId, mutation: item, error });

    return 'network';
  }

  /** Marca un elemento como conflicto sin descartarlo (`Art. III.1`). */
  async markAsConflict(item, conflict) {
    const marked = {
      ...item,
      status: MUTATION_STATUS.CONFLICT,
      conflict: {
        code: conflict.code,
        message: conflict.message,
        reason: conflict.message,
        detected_at: new Date(this.now()).toISOString()
      }
    };

    await this.storage.putMutation(marked);
    this.announce({ type: 'conflict', technicianId: item.technician_id, mutation: marked });

    return marked;
  }

  /** Último paso de inicio aún pendiente de la misma parada (`QA 4`). */
  findPriorStart(activeItems, stopType, stopId) {
    const candidates = activeItems.filter((item) => item.stop_type === stopType
      && Number(item.stop_id) === Number(stopId)
      && item.status === MUTATION_STATUS.PENDING
      && CHAIN_START_OPERATIONS.includes(item.operation_type));

    if (candidates.length === 0) {
      return null;
    }

    return candidates.reduce((latest, item) => (
      Number(item.sequence_order) > Number(latest.sequence_order) ? item : latest
    ));
  }

  /** Publica el cambio de la bandeja a quien esté escuchando. */
  announce(detail) {
    if (this.dispatch !== null) {
      this.dispatch(detail);
      return;
    }

    if (typeof globalThis === 'undefined' || typeof globalThis.dispatchEvent !== 'function'
      || typeof globalThis.CustomEvent !== 'function') {
      return;
    }

    globalThis.dispatchEvent(new globalThis.CustomEvent(OFFLINE_QUEUE_EVENT, { detail }));
  }
}

/** Texto recortado, o `null` cuando no hay nada real que contar. */
function textOrNull(value) {
  const text = typeof value === 'string' ? value.trim() : '';
  return text === '' ? null : text;
}

/**
 * ¿El rechazo es un veredicto definitivo del servidor sobre esta operación?
 *
 * Un 4xx es permanente salvo que el propio protocolo lo declare transitorio
 * (`408`, `425`, `429`). Tratarlo como caída de red deja el elemento en cabeza
 * del FIFO para siempre y arrastra a toda la cola con él.
 */
function isPermanentClientFailure(status) {
  return status >= 400 && status < 500 && !RETRYABLE_CLIENT_STATUSES.includes(status);
}

/** Constancia textual de la firma recogida in situ, o de su ausencia declarada. */
function conformitySummary(sheet) {
  if (sheet === null || sheet === undefined || typeof sheet !== 'object') {
    return null;
  }

  const signerName = textOrNull(sheet.signer_name);
  const signerDocument = textOrNull(sheet.signer_document);

  if (sheet.signature_png_base64) {
    const identity = signerName === null
      ? 'responsable de la sede'
      : `${signerName}${signerDocument === null ? '' : ` (${signerDocument})`}`;

    return `Firma manuscrita recogida in situ de ${identity}.`;
  }

  const absence = textOrNull(sheet.absence_reason);

  return absence === null
    ? textOrNull(sheet.conformity_type)
    : `Sin firma del responsable: ${absence}`;
}

/** Sufijo hexadecimal aleatorio: criptográfico cuando el entorno lo ofrece. */
function randomHex(length) {
  const cryptoApi = typeof globalThis !== 'undefined' ? globalThis.crypto : undefined;

  if (cryptoApi && typeof cryptoApi.getRandomValues === 'function') {
    const bytes = new Uint8Array(Math.ceil(length / 2));
    cryptoApi.getRandomValues(bytes);

    return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('').slice(0, length);
  }

  let output = '';
  while (output.length < length) {
    output += Math.floor(Math.random() * 16).toString(16);
  }

  return output.slice(0, length);
}

export default {
  OFFLINE_QUEUE_EVENT,
  OfflineSyncManager,
  generateClientMutationId,
  buildOfflineWorkSummary
};
